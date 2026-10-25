<?php
declare(strict_types=1);

/**
 * Lawang Sewu webview harvester — unit tests.
 * Pure assert-based, no frameworks. Run:
 *   php tests/test-webview-harvester.php
 * Exit 0 = all pass, 1 = failures.
 */

define('LS_TEST_MODE', true);
require __DIR__ . '/../tools/harvest-webview-knowledge.php';

$pass = 0;
$fail = 0;

function check(bool $cond, string $label): void
{
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  ok  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL {$label}\n";
    }
}

/* ---------- skin parser ---------- */

$skin = <<<'JS'
function skin() {
  var a = player._("<iframe src=https:\/\/webview-govirtual.id\/semarang-v2\/objinfo2.php?info=991&lang=id&vd=1&tp=m width=\"100%\"></iframe>");
  var b = player._("<iframe src=https:\/\/webview-govirtual.id\/semarang-v2\/objinfo2.php?info=992&lang=en&vd=1&tp=m width=\"100%\"></iframe>");
  var c = player._("<iframe src=\"https:\/\/www.chatbase.co\/chatbot-iframe\/KEKE_k50wrorpjLKzDxNl\" width=\"100%\"></iframe>");
  var d = player._("<iframe src=../webview/discover.php?disc=1&lang=id width=\"100%\"></iframe>");
  var e = player._("<iframe src=../webview/kategori.php?lang=id width=\"100%\"></iframe>");
  me._next_maket.onclick=function (e) {
    var hs = player._("<iframe src=https:\/\/webview-govirtual.id\/semarang-v2\/objinfo2.php?info=1200&lang=id&vd=1&tp=m width=\"100%\"></iframe>");
    player.setVariableValue('VB', "maket");
    player.openNext("{node42}","-160\/-20\/120");
    var namaAudio = 'maket';
  }
}
player.addTranslations({"languages":["ar","en-US","zh-CN"]});
JS;

$refs = ls_parse_iframes($skin);
check(count($refs) === 6, 'parse_iframes: 6 refs (3 objinfo2, 1 chatbase, 2 webview-local)');
check(count(array_filter($refs, static fn($r) => $r['kind'] === 'objinfo2')) === 3, 'parse_iframes: 3 objinfo2');
check(count(array_filter($refs, static fn($r) => $r['kind'] === 'chatbase')) === 1, 'parse_iframes: 1 chatbase');
check(ls_classify($refs[0]) === 'CONTENT', 'classify objinfo2 = CONTENT');
check(ls_classify($refs[2]) === 'IGNORE', 'classify chatbase = IGNORE');
check(ls_classify($refs[3]) === 'CONTENT-candidate', 'classify discover = CONTENT-candidate');
check(ls_classify($refs[4]) === 'INDEX', 'classify kategori = INDEX');

$core = ls_skin_core($skin);
check(!str_contains($core, 'addTranslations'), 'skin_core strips translations tail');

/* ---------- VB-state block mapping ---------- */

$blocks = ls_parse_vb_blocks($skin);
check(count($blocks) === 2, 'vb_blocks: 2 blocks (base + maket)');
check($blocks[1]['id'] === 1200 && $blocks[1]['state'] === 'maket', 'vb_blocks: id 1200 -> state maket');
check($blocks[1]['openNext'] === 'node42', 'vb_blocks: maket openNext node42');
check($blocks[1]['audio'] === 'maket', 'vb_blocks: audio name captured');

/* ---------- tour-data scene mapping ---------- */

$scenes = ls_parse_scenes(
    "window.GoVirtualTourData = { scenes: ["
    . "{ id: 'node42', rawName: 'Maket', displayName: 'Maket', name: 'Maket', aliases: ['maket'] },"
    . "{ id: 'node44', rawName: 'Cetak Biru', displayName: 'Cetak Biru', name: 'Cetak Biru', aliases: ['cetak biru', 'blueprint'] },"
    . "{ id: 'node60', rawName: 'Lokomotif Listrik', displayName: 'Lokomotif Listrik', name: 'Lokomotif Listrik', aliases: ['kereta listrik'] },"
    . "] }"
);
check(count($scenes) === 3, 'parse_scenes: 3 scenes');

$m = ls_map_scene('maket', 'node42', $scenes);
check($m['confidence'] === 'EXACT' && $m['sceneIds'] === ['node42'], 'map maket + openNext node42 = EXACT');

$m = ls_map_scene('kereta-listrik', 'node60', $scenes);
check($m['confidence'] === 'EXACT' && $m['sceneIds'] === ['node60'], 'map kereta-listrik + openNext node60 = EXACT');

$m = ls_map_scene('blueprint', null, $scenes);
check($m['confidence'] === 'LIKELY' && in_array('node44', $m['sceneIds'], true), 'map blueprint no openNext = LIKELY node44');

$m = ls_map_scene('gedung-c', null, $scenes);
check($m['confidence'] === 'UNMAPPED' && $m['sceneIds'] === [], 'map gedung-c no evidence = UNMAPPED');

/* ---------- contamination ---------- */

$entries = [
    ['id' => 'a', 'title' => 'Gedung A', 'aliases' => [], 'content' => 'Sejarah gedung', 'source' => 'http://x'],
    ['id' => 'b', 'title' => 'Tugu', 'aliases' => [], 'content' => 'Tugu marmer di depan Pelabuhan Bakauheni', 'source' => 'http://x'],
    ['id' => 'c', 'title' => 'Menara Siger view', 'aliases' => [], 'content' => 'text', 'source' => 'http://x'],
];
$v = ls_contamination_check($entries, []);
check(count($v) === 3, 'contamination: 3 violations caught');
$terms = array_column($v, 'term');
check(in_array('pelabuhan bakauheni', $terms, true) && in_array('menara siger', $terms, true), 'contamination: terms identified');

/* ---------- manual merge ---------- */

$tmp = tempnam(sys_get_temp_dir(), 'lsk');
file_put_contents($tmp, json_encode(['entries' => [
    ['id' => 'manual:1', 'title' => 'Manual', 'sourceType' => 'manual', 'content' => 'x'],
    ['id' => 'webview:objinfo2:991', 'title' => 'Old Harvest', 'sourceType' => 'objinfo2', 'content' => 'x'],
    ['id' => 'skin:welcome', 'title' => 'Old Inline', 'sourceType' => 'skin-inline', 'content' => 'x'],
]]));
$manual = ls_load_existing_manual($tmp);
unlink($tmp);
check(count($manual) === 1 && $manual[0]['id'] === 'manual:1', 'merge: manual preserved, harvested dropped');

/* ---------- knowledge.json integrity (live output) ---------- */

$kf = LS_ROOT . '/knowledge.json';
if (is_file($kf)) {
    $k = json_decode((string) file_get_contents($kf), true);
    check(is_array($k['entries'] ?? null), 'knowledge.json: entries array');
    check(($k['tourId'] ?? '') === 'lawang-sewu', 'knowledge.json: tourId lawang-sewu');

    $ids = [];
    $ok = true;
    foreach ($k['entries'] as $e) {
        if (isset($ids[$e['id']])) $ok = false;
        $ids[$e['id']] = true;
        foreach (['id', 'title', 'aliases', 'type', 'categories', 'sceneIds', 'routeIds', 'content', 'source'] as $req) {
            if (!array_key_exists($req, $e)) { $ok = false; echo "    missing field {$req} in {$e['id']}\n"; }
        }
    }
    check($ok, 'knowledge.json: unique ids + required schema fields');

    $objinfo = array_values(array_filter($k['entries'], static fn($e) => str_starts_with($e['id'], 'webview:objinfo2:')));
    check(count($objinfo) === 13, 'knowledge.json: 13 objinfo2 entities');

    $translationsOk = true;
    foreach ($objinfo as $e) {
        foreach (['id', 'en', 'sa', 'zh'] as $l) {
            if (empty($e['translations'][$l]['title'])) $translationsOk = false;
        }
        if (empty($e['content'])) $translationsOk = false;
        if (!empty($e['sceneIds']) && !in_array($e['mappingConfidence'], ['EXACT', 'LIKELY'], true)) $translationsOk = false;
    }
    check($translationsOk, 'knowledge.json: all objinfo2 have 4-lang translations + content + honest mapping');

    $mapped = array_filter($objinfo, static fn($e) => $e['mappingConfidence'] === 'EXACT');
    check(count($mapped) === 6, 'knowledge.json: 6 EXACT scene mappings');

    $contam = ls_contamination_check($objinfo, array_values(array_filter($k['entries'], static fn($e) => str_starts_with($e['id'], 'skin:'))));
    check($contam === [], 'knowledge.json: no contamination');

    $inline = array_values(array_filter($k['entries'], static fn($e) => str_starts_with($e['id'], 'skin:')));
    check(count($inline) === 3 && ($inline[2]['content'] ?? '') !== '', 'knowledge.json: 3 inline skin texts, all non-empty');
} else {
    check(false, 'knowledge.json exists (run harvester publish first)');
}

/* ---------- webview-map.json ---------- */

$wf = LS_WEBVIEW_MAP_OUT;
if (is_file($wf)) {
    $w = json_decode((string) file_get_contents($wf), true);
    check(count($w['entities'] ?? []) === 13, 'webview-map.json: 13 entities');
    $ok = ($w['entities']['1199']['state'] ?? '') === 'bekas-tembakan'
        && ($w['entities']['1199']['openNext'] ?? '') === 'node47';
    check($ok, 'webview-map.json: 1199 -> bekas-tembakan/node47');
    check(count($w['inventory']['ignored'] ?? []) >= 1, 'webview-map.json: ignored chatbase+webview-local listed');
} else {
    check(false, 'webview-map.json exists');
}

/* ---------- summary ---------- */

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    echo "TEST-WEBVIEW-HARVESTER: FAIL\n";
    exit(1);
}
echo "TEST-WEBVIEW-HARVESTER: PASS\n";
exit(0);
