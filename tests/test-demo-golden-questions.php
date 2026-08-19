<?php
declare(strict_types=1);
/**
 * Curated Demo Intelligence — Golden Answer Tests
 * Tests all 15 golden intents + aliases + dynamic behavior.
 * php tests/test-demo-golden-questions.php
 */
require_once __DIR__ . '/../api/ai-core.php';
require_once __DIR__ . '/../api/ai-provider.php';
require_once __DIR__ . '/../api/guide-policy.php';

putenv('OLLAMA_URL=http://127.0.0.1:1');
putenv('AI_REASONING_MODE=fast');

// Load tour data
$js = (string) file_get_contents(__DIR__ . '/../tour-data.js');
$json = trim((string) preg_replace('/^.*?\bwindow\.GoVirtualTourData\s*=\s*/s', '', $js));
$json = (string) preg_replace('/;\s*$/s', '', $json);
$raw = json_decode($json, true);
$tour = ai_canonical_tour(is_array($raw) ? $raw : []);

$pass = 0; $fail = 0; $failures = [];

function check(bool $cond, string $label): void {
    global $pass, $fail, $failures;
    if ($cond) { $pass++; echo "  ok  {$label}\n"; }
    else { $fail++; $failures[] = $label; echo "  FAIL {$label}\n"; }
}

echo "=== 15 GOLDEN INTENT MATCHING ===\n\n";

// G01: CURRENT LOCATION
$q = 'Di mana saya?';
$m = ai_curated_match($q, ['lastSceneId' => 'node51']);
check($m !== null && $m['id'] === 'G01_CURRENT_LOCATION', "G01 match 'Di mana saya?'");
$r = ai_curated_response('G01_CURRENT_LOCATION', $q, ['lastSceneId' => 'node51'], $tour);
check($r !== null && !str_contains($r['reply'], 'node'), "G01 no node ID leak");
check($r !== null && str_contains($r['reply'], 'Area Utama'), "G01 uses visitor place name");

// G01 aliases
$m2 = ai_curated_match('Saya sekarang berada di mana?', ['lastSceneId' => 'node51']);
check($m2 !== null && $m2['id'] === 'G01_CURRENT_LOCATION', "G01 alias 'Saya sekarang berada di mana?'");
$m3 = ai_curated_match('Ini area apa?', ['lastSceneId' => 'node51']);
check($m3 !== null && $m3['id'] === 'G01_CURRENT_LOCATION', "G01 alias 'Ini area apa?'");

// G02: AREA DESCRIPTION
$q = 'Ceritakan area ini.';
$m = ai_curated_match($q, ['lastSceneId' => 'node51']);
check($m !== null && $m['id'] === 'G02_AREA_DESCRIPTION', "G02 match 'Ceritakan area ini.'");
$r = ai_curated_response('G02_AREA_DESCRIPTION', $q, ['lastSceneId' => 'node51'], $tour);
check($r !== null && !str_contains($r['reply'], 'belum ada deskripsi'), "G02 no 'belum ada deskripsi'");
check($r !== null && str_contains($r['reply'], 'Area Utama'), "G02 uses visitor place name");

// G02 aliases
$m2 = ai_curated_match('Jelaskan tempat yang sedang saya lihat.', ['lastSceneId' => 'node51']);
check($m2 !== null && $m2['id'] === 'G02_AREA_DESCRIPTION', "G02 alias 'Jelaskan tempat yang sedang saya lihat.'");
$m3 = ai_curated_match('Apa yang ada di area ini?', ['lastSceneId' => 'node51']);
check($m3 !== null && $m3['id'] === 'G02_AREA_DESCRIPTION', "G02 alias 'Apa yang ada di area ini?'");

// G03: TOUR OVERVIEW
$m = ai_curated_match('Apa yang menarik dari Lawang Sewu?', []);
check($m !== null && $m['id'] === 'G03_TOUR_OVERVIEW', "G03 match 'Apa yang menarik dari Lawang Sewu?'");
$m2 = ai_curated_match('Ceritakan Lawang Sewu.', []);
check($m2 !== null && $m2['id'] === 'G03_TOUR_OVERVIEW', "G03 alias 'Ceritakan Lawang Sewu.'");
$m3 = ai_curated_match('Apa yang istimewa dari Lawang Sewu?', []);
check($m3 !== null && $m3['id'] === 'G03_TOUR_OVERVIEW', "G03 alias 'Apa yang istimewa dari Lawang Sewu?'");

// G04: NAME ORIGIN
$m = ai_curated_match('Kenapa disebut Lawang Sewu?', []);
check($m !== null && $m['id'] === 'G04_NAME_ORIGIN', "G04 match 'Kenapa disebut Lawang Sewu?'");
$m2 = ai_curated_match('Apa arti Lawang Sewu?', []);
check($m2 !== null && $m2['id'] === 'G04_NAME_ORIGIN', "G04 alias 'Apa arti Lawang Sewu?'");
$m3 = ai_curated_match('Kenapa namanya Lawang Sewu?', []);
check($m3 !== null && $m3['id'] === 'G04_NAME_ORIGIN', "G04 alias 'Kenapa namanya Lawang Sewu?'");

// G05: GEDUNG A
$m = ai_curated_match('Ceritakan Gedung A.', []);
check($m !== null && $m['id'] === 'G05_GEDUNG_A', "G05 match 'Ceritakan Gedung A.'");
$m2 = ai_curated_match('Apa itu Gedung A?', []);
check($m2 !== null && $m2['id'] === 'G05_GEDUNG_A', "G05 alias 'Apa itu Gedung A?'");
$m3 = ai_curated_match('Jelaskan tentang Gedung A.', []);
check($m3 !== null && $m3['id'] === 'G05_GEDUNG_A', "G05 alias 'Jelaskan tentang Gedung A.'");

// G06: TUGU MARMER
$m = ai_curated_match('Apa itu Tugu Marmer?', []);
check($m !== null && $m['id'] === 'G06_TUGU_MARMER', "G06 match 'Apa itu Tugu Marmer?'");
$m2 = ai_curated_match('Ceritakan Tugu Marmer.', []);
check($m2 !== null && $m2['id'] === 'G06_TUGU_MARMER', "G06 alias 'Ceritakan Tugu Marmer.'");
$m3 = ai_curated_match('Tugu Marmer itu apa?', []);
check($m3 !== null && $m3['id'] === 'G06_TUGU_MARMER', "G06 alias 'Tugu Marmer itu apa?'");

// G07: NIS
$m = ai_curated_match('Apa itu NIS?', []);
check($m !== null && $m['id'] === 'G07_NIS', "G07 match 'Apa itu NIS?'");
$m2 = ai_curated_match('NIS itu apa?', []);
check($m2 !== null && $m2['id'] === 'G07_NIS', "G07 alias 'NIS itu apa?'");
$m3 = ai_curated_match('Apa hubungan NIS dengan Lawang Sewu?', []);
check($m3 !== null && $m3['id'] === 'G07_NIS', "G07 alias 'Apa hubungan NIS dengan Lawang Sewu?'");

// G08: COLONIAL ARCHITECTURE
$m = ai_curated_match('Apa itu arsitektur kolonial?', []);
check($m !== null && $m['id'] === 'G08_COLONIAL_ARCHITECTURE', "G08 match 'Apa itu arsitektur kolonial?'");
$m2 = ai_curated_match('Seperti apa arsitektur kolonial?', []);
check($m2 !== null && $m2['id'] === 'G08_COLONIAL_ARCHITECTURE', "G08 alias 'Seperti apa arsitektur kolonial?'");
$m3 = ai_curated_match('Apa maksud arsitektur kolonial?', []);
check($m3 !== null && $m3['id'] === 'G08_COLONIAL_ARCHITECTURE', "G08 alias 'Apa maksud arsitektur kolonial?'");

// G09: STAINED GLASS
$m = ai_curated_match('Apa fungsi kaca patri?', []);
check($m !== null && $m['id'] === 'G09_STAINED_GLASS', "G09 match 'Apa fungsi kaca patri?'");
$m2 = ai_curated_match('Kenapa ada kaca patri?', []);
check($m2 !== null && $m2['id'] === 'G09_STAINED_GLASS', "G09 alias 'Kenapa ada kaca patri?'");
$m3 = ai_curated_match('Ceritakan kaca patri Lawang Sewu.', []);
check($m3 !== null && $m3['id'] === 'G09_STAINED_GLASS', "G09 alias 'Ceritakan kaca patri Lawang Sewu.'");

// G10: RAILWAY HISTORY
$m = ai_curated_match('Apa hubungan Lawang Sewu dengan kereta api?', []);
check($m !== null && $m['id'] === 'G10_RAILWAY_HISTORY', "G10 match 'Apa hubungan Lawang Sewu dengan kereta api?'");
$m2 = ai_curated_match('Lawang Sewu dulunya kantor apa?', []);
check($m2 !== null && $m2['id'] === 'G10_RAILWAY_HISTORY', "G10 alias 'Lawang Sewu dulunya kantor apa?'");

// G11: SEMARANG IMPORTANCE
$m = ai_curated_match('Kenapa Lawang Sewu penting bagi Semarang?', []);
check($m !== null && $m['id'] === 'G11_SEMARANG_IMPORTANCE', "G11 match 'Kenapa Lawang Sewu penting bagi Semarang?'");
$m2 = ai_curated_match('Kenapa Lawang Sewu menjadi ikon Semarang?', []);
check($m2 !== null && $m2['id'] === 'G11_SEMARANG_IMPORTANCE', "G11 alias 'Kenapa Lawang Sewu menjadi ikon Semarang?'");

// G12: ROUTE RECOMMENDATION
$m = ai_curated_match('Setelah dari sini sebaiknya saya ke mana?', ['lastSceneId' => 'node51']);
check($m !== null && $m['id'] === 'G12_ROUTE_RECOMMENDATION', "G12 match 'Setelah dari sini sebaiknya saya ke mana?'");
$r = ai_curated_response('G12_ROUTE_RECOMMENDATION', 'Setelah dari sini sebaiknya saya ke mana?', ['lastSceneId' => 'node51'], $tour);
check($r !== null && ($r['recommendation_target']['sceneId'] ?? '') !== '', "G12 stores recommendation target");
check($r !== null && !str_contains($r['reply'], 'node'), "G12 no node ID leak");

// G12 aliases
$m2 = ai_curated_match('Rekomendasi berikutnya.', ['lastSceneId' => 'node51']);
check($m2 !== null && $m2['id'] === 'G12_ROUTE_RECOMMENDATION', "G12 alias 'Rekomendasi berikutnya.'");

// G13: FOLLOWUP NAVIGATION
$sessionWithRec = ['lastSceneId' => 'node51', 'lastRecommendedDestination' => ['sceneId' => 'node45', 'placeId' => 'gedung-d', 'label' => 'Gedung D']];
$m = ai_curated_match('Bawa saya ke sana.', $sessionWithRec);
check($m !== null && $m['id'] === 'G13_FOLLOWUP_NAVIGATION', "G13 match 'Bawa saya ke sana.'");
$r = ai_curated_response('G13_FOLLOWUP_NAVIGATION', 'Bawa saya ke sana.', $sessionWithRec, $tour);
check($r !== null && $r['action'] === 'go_to_scene', "G13 action is go_to_scene");
check($r !== null && $r['scene'] === 'node45', "G13 scene is node45");

// G13 WITHOUT recommendation state → should NOT match
$m2 = ai_curated_match('Bawa saya ke sana.', ['lastSceneId' => 'node51']);
check($m2 === null || $m2['id'] !== 'G13_FOLLOWUP_NAVIGATION', "G13 without recommendation state → no match");

// G14: NAVIGATION RAILWAY GALLERY
$m = ai_curated_match('Bawa saya ke Galeri Museum Kereta Api.', []);
check($m !== null && $m['id'] === 'G14_NAVIGATION_RAILWAY_GALLERY', "G14 match 'Bawa saya ke Galeri Museum Kereta Api.'");
$r = ai_curated_response('G14_NAVIGATION_RAILWAY_GALLERY', $q, ['lastSceneId' => 'node51'], $tour);
check($r !== null && $r['action'] === 'go_to_scene', "G14 action is go_to_scene");
check($r !== null && str_starts_with($r['scene'], 'node'), "G14 scene is valid node");

// G15: NAVIGATION GEDUNG D
$m = ai_curated_match('Bawa saya ke Gedung D.', []);
check($m !== null && $m['id'] === 'G15_NAVIGATION_GEDUNG_D', "G15 match 'Bawa saya ke Gedung D.'");
$r = ai_curated_response('G15_NAVIGATION_GEDUNG_D', $q, ['lastSceneId' => 'node51'], $tour);
check($r !== null && $r['action'] === 'go_to_scene', "G15 action is go_to_scene");
check($r !== null && $r['scene'] === 'node45', "G15 scene is node45");

echo "\n=== NEGATIVE TESTS (must NOT match informational curated) ===\n\n";

// Navigation words must not trigger informational intents
$m = ai_curated_match('Bawa saya ke Gedung A.', []);
check($m === null || $m['category'] !== 'factual', "'Bawa saya ke Gedung A' not informational");
$m2 = ai_curated_match('Pergi ke Tugu Marmer.', []);
check($m2 === null || $m2['category'] !== 'factual', "'Pergi ke Tugu Marmer' not informational");

// Unrelated questions should NOT match curated
$m = ai_curated_match('Apa makanan khas Semarang?', []);
check($m === null || ($m['id'] ?? '') !== 'G03_TOUR_OVERVIEW', "'Apa makanan khas Semarang?' not G03");
$m2 = ai_curated_match('Semarang ada di provinsi mana?', []);
check($m2 === null, "'Semarang ada di provinsi mana?' no curated match");

echo "\n=== NO TECHNICAL LABELS IN RESPONSES ===\n\n";

$technicalLabels = ['Main Siang', 'Main Malam', 'Hall Siang', 'Hall Malam', 'Default - Day', 'Default - Night', 'node39', 'node45', 'node51', 'node53', 'node61', 'node64', 'node73'];
foreach (['G01_CURRENT_LOCATION', 'G02_AREA_DESCRIPTION', 'G03_TOUR_OVERVIEW', 'G04_NAME_ORIGIN', 'G05_GEDUNG_A', 'G06_TUGU_MARMER', 'G07_NIS', 'G08_COLONIAL_ARCHITECTURE', 'G09_STAINED_GLASS', 'G10_RAILWAY_HISTORY', 'G11_SEMARANG_IMPORTANCE'] as $intentId) {
    $r = ai_curated_response($intentId, '', ['lastSceneId' => 'node51'], $tour);
    if ($r) {
        foreach ($technicalLabels as $label) {
            check(!str_contains($r['reply'], $label), "{$intentId} no technical label '{$label}'");
        }
    }
}

echo "\n=== STATEFUL CONVERSATION TEST (5 turns) ===\n\n";

$session = ['lastSceneId' => 'node51', 'lastRecommendedDestination' => null];

// Turn 1: Di mana saya?
$m1 = ai_curated_match('Di mana saya?', $session);
$r1 = $m1 ? ai_curated_response($m1['id'], 'Di mana saya?', $session, $tour) : null;
check($r1 !== null && str_contains($r1['reply'], 'Area Utama'), "T1: Di mana saya? → Area Utama");
check($r1['action'] === 'none', "T1: no action");

// Turn 2: Ceritakan area ini.
$m2 = ai_curated_match('Ceritakan area ini.', $session);
$r2 = $m2 ? ai_curated_response($m2['id'], 'Ceritakan area ini.', $session, $tour) : null;
check($r2 !== null && str_contains($r2['reply'], 'Area Utama'), "T2: Ceritakan area ini → Area Utama");
check($r2['action'] === 'none', "T2: no action");

// Turn 3: Setelah dari sini sebaiknya saya ke mana?
$m3 = ai_curated_match('Setelah dari sini sebaiknya saya ke mana?', $session);
$r3 = $m3 ? ai_curated_response($m3['id'], 'Setelah dari sini sebaiknya saya ke mana?', $session, $tour) : null;
check($r3 !== null && !empty($r3['recommendation_target']), "T3: recommendation target stored");
check($r3['action'] === 'none', "T3: no action");
if ($r3 && !empty($r3['recommendation_target'])) {
    $session['lastRecommendedDestination'] = $r3['recommendation_target'];
}

// Turn 4: Apa itu arsitektur kolonial? (MUST NOT navigate)
$m4 = ai_curated_match('Apa itu arsitektur kolonial?', $session);
$r4 = $m4 ? ai_curated_response($m4['id'], 'Apa itu arsitektur kolonial?', $session, $tour) : null;
check($r4 !== null && $r4['action'] === 'none', "T4: arsitektur kolonial → no navigation");
check($r4 !== null && str_contains($r4['reply'], 'arsitektur kolonial'), "T4: reply about colonial architecture");

// Turn 5: Oke, bawa saya ke sana.
$m5 = ai_curated_match('Oke, bawa saya ke sana.', $session);
$r5 = $m5 ? ai_curated_response($m5['id'], 'Oke, bawa saya ke sana.', $session, $tour) : null;
check($r5 !== null && $r5['action'] === 'go_to_scene', "T5: follow-up → go_to_scene");
check($r5 !== null && $r5['scene'] === ($session['lastRecommendedDestination']['sceneId'] ?? ''), "T5: scene matches recommendation");

echo "\n=== GENERAL AI FALLTHROUGH ===\n\n";

// These should NOT match any curated intent
$unrelated = [
    'Apa makanan khas Semarang?',
    'Apa fungsi ventilasi pada bangunan kolonial?',
    'Semarang ada di provinsi mana?',
    'Apa itu perusahaan kereta api?',
    'Kenapa bangunan lama punya langit-langit tinggi?',
];
foreach ($unrelated as $uq) {
    $um = ai_curated_match($uq, ['lastSceneId' => 'node51']);
    check($um === null, "No curated match: '{$uq}'");
}

echo "\n=== SUMMARY ===\n";
echo "{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "FAILURES:\n";
    foreach ($failures as $f) echo "  - {$f}\n";
    echo "\nDEMO GOLDEN: NOT READY\n";
    exit(1);
}
echo "\nDEMO GOLDEN: PASS\n";
exit(0);
