<?php
declare(strict_types=1);

/**
 * Lawang Sewu AI acceptance tests (offline retrieval).
 * Verifies knowledge.json feeds ai_retrieve correctly without any network.
 *   php api/../tests/test-ai-acceptance.php
 * Exit 0 = PASS, 1 = FAIL.
 */

require __DIR__ . '/../api/ai-core.php';

putenv('OLLAMA_URL=http://127.0.0.1:1'); // force lexical fallback, fully offline
putenv('AI_REASONING_MODE=fast');        // no embedding calls at all

$config = ai_config();

// Parse tour-data.js: window.GoVirtualTourData = { ... };  (JS wrapper, not bare JSON)
$js = (string) file_get_contents(__DIR__ . '/../tour-data.js');
$json = trim((string) preg_replace('/^.*?\bwindow\.GoVirtualTourData\s*=\s*/s', '', $js));
$json = (string) preg_replace('/;\s*$/s', '', $json);
$raw = json_decode($json, true);
$tour = ai_canonical_tour(is_array($raw) ? $raw : []);

$pass = 0;
$fail = 0;
function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ok  {$label}\n"; }
    else { $fail++; echo "  FAIL {$label}\n"; }
}

/* 9 supported questions — retrieval must surface the right entry. */
$q = [
    'Gedung A Lawang Sewu'                              => 'webview:objinfo2:991',
    'ceritakan maket proyek'                            => 'webview:objinfo2:1200',
    'lokomotif uap C23'                                 => 'webview:objinfo2:977',
    'lokomotif listrik ESS'                             => 'webview:objinfo2:978',
    'cetak biru gedung'                                 => 'webview:objinfo2:631',
    'bekas tembakan di Lawang Sewu'                     => 'webview:objinfo2:1199',
    'sejarah revitalisasi Lawang Sewu'                  => 'webview:objinfo2:1201',
    'mini show'                                          => 'webview:objinfo2:1198',
    'tugu marmer'                                        => 'webview:objinfo2:632',
];
foreach ($q as $message => $expectedId) {
    $norm = preg_replace('/[^a-zA-Z0-9_-]/', '', $expectedId);
    $want = 'knowledge-' . $norm;
    $ret = ai_retrieve($message, ['lastSceneId' => ''], $tour, $config, 3);
    $ids = array_column($ret['items'], 'entry');
    $ids = array_column($ids, 'id');
    $hit = in_array($want, $ids, true);
    check($hit, "retrieve '{$message}' -> " . implode(',', $ids ?: ['none']) . " (want {$want} in top-3)");
}

/*
 * 5 unsupported / hallucination-prone questions.
 * Lexical fallback is recall-oriented; the anti-hallucination contract is:
 *   1) no contamination terms (Siger/Bakauheni) ever surface,
 *   2) no retrieved knowledge content mentions the unsupported topic.
 * The LLM grounding prompt then has nothing to invent from.
 */
$unsupported = [
    'Berapa harga tiket masuk Lawang Sewu?' => ['tiket', 'harga'],
    'Jam buka museum?' => ['jam buka', 'jam operasional'],
    'Ceritakan Menara Siger' => ['siger'],
    'dimana Pelabuhan Bakauheni' => ['bakauheni'],
    'ceritakan hantu lawang sewu' => ['hantu'],
];
foreach ($unsupported as $message => $forbidden) {
    $ret = ai_retrieve($message, ['lastSceneId' => ''], $tour, $config, 6);
    $blob = strtolower(implode(' ', array_map(
        static fn($i) => $i['entry']['title'] . ' ' . $i['entry']['content'],
        $ret['items']
    )));
    $hits = array_filter($forbidden, static fn($f) => str_contains($blob, $f));
    check($hits === [], "'{$message}': no retrieved knowledge mentions " . implode('/', $forbidden));
}

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) { echo "AI-ACCEPTANCE: NOT READY\n"; exit(1); }
echo "AI-ACCEPTANCE: PASS\n";
exit(0);
