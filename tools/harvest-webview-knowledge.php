<?php
declare(strict_types=1);

/**
 * Lawang Sewu — Webview Knowledge Harvester (CLI / build-time only).
 *
 * Extraction-only. No invented facts. Sources of truth:
 *   1. lawang sewu/skin.js            iframe inventory, VB-state -> objinfo2-ID mapping,
 *                                     openNext scene navigation, inline texts.
 *   2. https://webview-govirtual.id/semarang-v2/objinfo2.php?info={ID}&lang={lang}
 *                                     per-entity multilingual content (fetched at build time,
 *                                     cached under api/cache/ai/webview/).
 *   3. lawang sewu/tour-data.js       scene names/aliases for scene-mapping evidence.
 *
 * Outputs:
 *   lawang sewu/knowledge.json               loader path (ai-core.php dirname(__DIR__))
 *   lawang sewu/api/knowledge.json           spec-required copy
 *   lawang sewu/api/cache/ai/webview-map.json
 *   lawang sewu/webview-harvest-report.json
 *
 * Usage:
 *   php tools/harvest-webview-knowledge.php [--dry-run] [--no-fetch] [--report-only] [--rebuild-embeddings]
 *
 * Exit codes: 0 ok, 1 error, 2 contamination (publish blocked), 3 fetch failures.
 */

const LS_ROOT             = __DIR__ . '/..';
const LS_SKIN_FILE        = LS_ROOT . '/skin.js';
const LS_TOUR_DATA_FILE   = LS_ROOT . '/tour-data.js';
const LS_KNOWLEDGE_OUT    = LS_ROOT . '/knowledge.json';
const LS_KNOWLEDGE_SPEC   = LS_ROOT . '/api/knowledge.json';
const LS_WEBVIEW_MAP_OUT  = LS_ROOT . '/api/cache/ai/webview-map.json';
const LS_REPORT_OUT       = LS_ROOT . '/webview-harvest-report.json';
const LS_RAW_CACHE_DIR    = LS_ROOT . '/api/cache/ai/webview';
const LS_OBJINFO_BASE     = 'https://webview-govirtual.id/semarang-v2/objinfo2.php';
const LS_ALLOWED_HOST     = 'webview-govirtual.id';
const LS_LANGS            = ['id', 'en', 'sa', 'zh'];
const LS_FETCH_TIMEOUT    = 15;

const LS_CONTENT_STATES = [
    'gedung-a', 'gedung-b', 'gedung-c', 'gedung-d',
    'tugu', 'kereta-uap', 'kereta-listrik', 'blueprint',
    'tugu-marmer', 'mini-show', 'bekas-tembakan', 'maket',
    'sejarah-revitalisasi',
];

const LS_CONTAMINATION_TERMS = ['bakauheni', 'pelabuhan bakauheni', 'menara siger'];

const LS_STATE_KEYWORDS = [
    'gedung-a'            => ['gedung a'],
    'gedung-b'            => ['gedung b'],
    'gedung-c'            => ['gedung c'],
    'gedung-d'            => ['gedung d'],
    'tugu'                => ['tugu'],
    'kereta-uap'          => ['kereta uap', 'lokomotif uap'],
    'kereta-listrik'      => ['kereta listrik', 'lokomotif listrik'],
    'blueprint'           => ['cetak biru', 'blueprint'],
    'tugu-marmer'         => ['tugu marmer'],
    'mini-show'           => ['mini show'],
    'bekas-tembakan'      => ['bekas tembakan'],
    'maket'               => ['maket'],
    'sejarah-revitalisasi'=> ['sejarah revitalisasi', 'revitalisasi'],
];

/* ---------------- helpers ---------------- */

function ls_fail(string $msg): void
{
    fwrite(STDERR, "harvest-webview-knowledge: ERROR: {$msg}\n");
    exit(1);
}

function ls_read(string $file): string
{
    $s = @file_get_contents($file);
    if ($s === false) ls_fail("cannot read {$file}");
    return $s;
}

function ls_write(string $file, string $data): void
{
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) ls_fail("cannot create dir {$dir}");
    if (@file_put_contents($file, $data) === false) ls_fail("cannot write {$file}");
}

function ls_json(array $data): string
{
    return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function ls_norm(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
}

function ls_line_at(string $skin, int $pos): int
{
    return substr_count(substr($skin, 0, $pos), "\n") + 1;
}

/* ---------------- 1. parse skin.js ---------------- */

/**
 * Split skin.js before the addTranslations() tail (it re-embeds every iframe
 * string and would double-count the inventory).
 */
function ls_skin_core(string $skin): string
{
    $p = strpos($skin, 'player.addTranslations');
    return $p === false ? $skin : substr($skin, 0, $p);
}

function ls_parse_iframes(string $skin): array
{
    $refs = [];

    preg_match_all('#objinfo2\.php\?info=(\d+)&lang=(\w+)#', $skin, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => $g) {
        $refs[] = [
            'kind' => 'objinfo2', 'id' => (int) $g[0], 'lang' => $m[2][$i][0],
            'pos' => $g[1], 'canonical' => 'objinfo2:' . $g[0],
        ];
    }

    preg_match_all('#\.\.?/webview/([a-z]+\.php)#', $skin, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => $g) {
        $refs[] = [
            'kind' => 'webview-local', 'page' => $g[0], 'pos' => $g[1],
            'canonical' => 'webview-local:' . $g[0],
        ];
    }

    // skin.js escapes slashes in JS strings (https:\/\/www.chatbase.co\/...); accept both.
    preg_match_all('#chatbase\.co(?:\\\\?/)[A-Za-z0-9_\\\\/\-]+#', $skin, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[0] as $g) {
        $refs[] = ['kind' => 'chatbase', 'url' => $g[0], 'pos' => $g[1], 'canonical' => 'chatbase'];
    }

    usort($refs, static fn(array $a, array $b): int => $a['pos'] <=> $b['pos']);
    return $refs;
}

function ls_classify(array $ref): string
{
    return match ($ref['kind']) {
        'objinfo2'     => 'CONTENT',
        'chatbase'     => 'IGNORE',
        'webview-local'=> in_array($ref['page'] ?? '', ['discover.php', 'informasi.php', 'publicservice.php'], true)
            ? 'CONTENT-candidate'
            : 'INDEX',
    };
}

/**
 * VB-state -> objinfo2-ID / openNext mapping, derived from skin.js structure:
 * each `me._text_vb_mobile.ggUpdateText=function(){...}` block carries one
 * objinfo2 iframe; the content VB state assigned inside the same onclick
 * handler sits within a few lines of that iframe.
 */
/**
 * Intro/rotation handlers navigate to a scene but do NOT associate that scene
 * with the entity content (the rotation just moves on). Exclude their openNext.
 */
const LS_INTRO_HANDLERS = ['mnegulang', 'yes_button_1', 'start_mobile', 'button_vb'];

function ls_parse_vb_blocks(string $skin): array
{
    // handler spans: me._<name>.onclick=function ... until next handler
    $handlers = [];
    preg_match_all('/me\._([a-z_0-9]+)\.onclick=function/', $skin, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => $g) {
        $handlers[] = ['name' => $g[0], 'pos' => $g[1]];
    }
    $n = count($handlers);

    $blocks = [];
    for ($i = 0; $i < $n; $i++) {
        $start = $handlers[$i]['pos'];
        $end   = $i + 1 < $n ? $handlers[$i + 1]['pos'] : strlen($skin);
        $chunk = substr($skin, $start, $end - $start);
        $name  = $handlers[$i]['name'];

        $state = null;
        if (preg_match('/setVariableValue\(\'VB\',\s*"([a-z\-]+)"\)/', $chunk, $vm) && in_array($vm[1], LS_CONTENT_STATES, true)) {
            $state = $vm[1];
        }
        $id = preg_match('/objinfo2\.php\?info=(\d+)/', $chunk, $im) ? (int) $im[1] : null;
        if ($state === null && $id === null) continue; // no entity content in this handler

        $isIntro = in_array($name, LS_INTRO_HANDLERS, true);
        $openNext = null;
        if (!$isIntro && preg_match('/openNext\("\{?([a-z0-9_]+)\}?"/', $chunk, $nm)) {
            $openNext = $nm[1];
        }
        $audio = preg_match("/namaAudio\s*=\s*'([^']+)'/", $chunk, $am) ? $am[1] : null;

        $blocks[] = [
            'id'       => $id ?? -1,
            'state'    => $state ?? 'gedung-a',
            'openNext' => $openNext,
            'audio'    => $audio,
            'handler'  => $name,
            'line'     => ls_line_at($skin, $start),
            'vbLine'   => $state !== null && preg_match('/setVariableValue\(\'VB\',\s*"([a-z\-]+)"\)/', $chunk, $vm2)
                ? ls_line_at($skin, $start + strpos($chunk, 'setVariableValue'))
                : null,
            'infoLine' => $id !== null ? ls_line_at($skin, $start + strpos($chunk, 'objinfo2.php')) : null,
        ];
    }

    // base block: first objinfo2 ref not claimed by any handler
    $infoRefs = [];
    preg_match_all('#objinfo2\.php\?info=(\d+)#', $skin, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $g) $infoRefs[] = ['id' => (int) $g[0], 'pos' => $g[1]];
    $claimed = array_column($blocks, 'id');
    foreach ($infoRefs as $in) {
        if (!in_array($in['id'], $claimed, true)) {
            array_unshift($blocks, [
                'id' => $in['id'], 'state' => 'gedung-a', 'openNext' => null, 'audio' => null,
                'handler' => 'default', 'line' => null, 'vbLine' => null,
                'infoLine' => ls_line_at($skin, $in['pos']),
            ]);
            break;
        }
    }

    // dedupe by id; drop negative ids; prefer non-intro block
    $byId = [];
    foreach ($blocks as $b) {
        if ($b['id'] < 0) continue;
        $key = $b['id'];
        $keep = !isset($byId[$key]);
        if (isset($byId[$key]) && !in_array($b['handler'], LS_INTRO_HANDLERS, true)
            && in_array($byId[$key]['handler'], LS_INTRO_HANDLERS, true)) {
            $keep = true;
        }
        if ($keep) $byId[$key] = $b;
    }
    $blocks = array_values($byId);

    return $blocks;
}

/* ---------------- 2. tour-data.js scenes ---------------- */

function ls_parse_scenes(string $js): array
{
    $scenes = [];
    preg_match_all(
        "/id: '(\w+)', rawName: '([^']*)', displayName: '([^']*)', name: '([^']*)', aliases: \[([^\]]*)\]/",
        $js, $m, PREG_SET_ORDER
    );
    foreach ($m as $g) {
        $aliases = [];
        preg_match_all("/'([^']+)'/", $g[5], $am);
        foreach ($am[1] as $a) $aliases[] = $a;
        $aliases[] = $g[1]; // rawName
        $aliases[] = $g[2]; // displayName
        $scenes[] = ['id' => $g[1], 'names' => array_unique(array_map('ls_norm', array_filter($aliases)))];
    }
    return $scenes;
}

function ls_map_scene(string $state, ?string $openNext, array $scenes): array
{
    $keywords = array_map('ls_norm', LS_STATE_KEYWORDS[$state] ?? []);
    $candidates = [];
    foreach ($scenes as $s) {
        foreach ($s['names'] as $n) {
            foreach ($keywords as $k) {
                if ($k !== '' && str_contains($n, $k)) { $candidates[] = $s['id']; break 2; }
            }
        }
    }
    $candidates = array_values(array_unique($candidates));

    if ($openNext !== null && in_array($openNext, $candidates, true)) {
        return ['sceneIds' => [$openNext], 'confidence' => 'EXACT',
            'evidence' => "openNext={$openNext} + tour-data alias match"];
    }
    if ($openNext !== null) {
        return ['sceneIds' => [$openNext], 'confidence' => 'LIKELY',
            'evidence' => "openNext={$openNext} (no alias match)"];
    }
    if (count($candidates) === 1) {
        return ['sceneIds' => $candidates, 'confidence' => 'LIKELY',
            'evidence' => 'tour-data alias match (no openNext)'];
    }
    if (count($candidates) > 1) {
        return ['sceneIds' => $candidates, 'confidence' => 'AMBIGUOUS',
            'evidence' => 'multiple alias matches: ' . implode(',', $candidates)];
    }
    return ['sceneIds' => [], 'confidence' => 'UNMAPPED', 'evidence' => 'no openNext, no alias match'];
}

/* ---------------- 3. fetch + extract ---------------- */

function ls_http_get(string $url): array
{
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https' || strtolower($parts['host'] ?? '') !== LS_ALLOWED_HOST) {
        return ['ok' => false, 'error' => "SSRF blocked: {$url}", 'status' => 0];
    }
    for ($attempt = 1; $attempt <= 2; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => LS_FETCH_TIMEOUT,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'lawang-sewu-harvester/1.0 (+build-time)',
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);
        $transient = $errno !== 0 || $status >= 500 || $status === 429 || $status === 408;
        if (!$transient) {
            return ['ok' => $status === 200 && is_string($body), 'status' => $status,
                'body' => is_string($body) ? $body : '', 'error' => $error];
        }
        if ($attempt === 1) usleep(500_000); // one retry after a short pause
    }
    return ['ok' => false, 'status' => $status, 'body' => '', 'error' => $error ?? "transient after retry"];
}

function ls_fetch_objinfo(int $id, string $lang, bool $useCache): array
{
    $url = LS_OBJINFO_BASE . "?info={$id}&lang={$lang}&vd=1&tp=m";
    $cacheFile = LS_RAW_CACHE_DIR . "/objinfo2-{$id}-{$lang}.html";

    if ($useCache) {
        if (!is_file($cacheFile)) {
            return ['ok' => false, 'body' => '', 'url' => $url, 'cached' => false, 'status' => 0, 'error' => 'cache-missing'];
        }
        $body = (string) file_get_contents($cacheFile);
        if ($body === '') {
            return ['ok' => false, 'body' => '', 'url' => $url, 'cached' => false, 'status' => 0, 'error' => 'cache-empty'];
        }
        return ['ok' => true, 'body' => $body, 'url' => $url, 'cached' => true, 'status' => 200];
    }

    $res = ls_http_get($url);
    if ($res['ok']) {
        if (!is_dir(LS_RAW_CACHE_DIR)) @mkdir(LS_RAW_CACHE_DIR, 0775, true);
        @file_put_contents($cacheFile, $res['body']);
    }
    return ['ok' => $res['ok'], 'body' => $res['body'], 'url' => $url, 'cached' => false,
        'status' => $res['status'] ?? 0, 'error' => $res['error'] ?? ''];
}

function ls_extract_html(string $html): array
{
    if ($html === '') return ['title' => '', 'paragraphs' => []];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    $xp = new DOMXPath($doc);
    $title = '';
    $h1 = $xp->query('//h1[1]');
    if ($h1 && $h1->length) $title = trim((string) $h1->item(0)->textContent);
    if ($title === '') {
        $t = $xp->query('//title[1]');
        if ($t && $t->length) $title = trim((string) $t->item(0)->textContent);
    }
    $paragraphs = [];
    $nodes = $xp->query('//p[contains(@class,"mb-4")]');
    if ($nodes) {
        foreach ($nodes as $p) {
            $txt = trim((string) preg_replace('/\s+/u', ' ', (string) $p->textContent));
            if ($txt !== '') $paragraphs[] = $txt;
        }
    }
    return ['title' => $title, 'paragraphs' => array_values(array_unique($paragraphs))];
}

/* ---------------- 4. inline skin texts ---------------- */

function ls_inline_texts(string $skin): array
{
    $defs = [
        ['id' => 'welcome-lawang-sewu', 'line' => 15542, 'title' => 'Selamat Datang di Lawang Sewu', 'type' => 'overview', 'needle' => 'Selamat datang di Lawang Sewu'],
        ['id' => 'virtual-guide',       'line' => 11502, 'title' => 'Pemandu Virtual',              'type' => 'information', 'needle' => 'pemandu virtual'],
        ['id' => 'semarang-city',       'line' => 18068, 'title' => 'Kota Semarang',                'type' => 'information', 'needle' => 'Kota Semarang'],
    ];
    // byte offset of each target line (anchor search to the line, avoid earlier ggId hits)
    $lineOffsets = [1 => 0];
    foreach (explode("\n", $skin) as $i => $_) {
        $lineOffsets[$i + 2] = $lineOffsets[$i + 1] + strlen($_) + 1;
    }
    $out = [];
    foreach ($defs as $d) {
        $lineOffset = $lineOffsets[$d['line']] ?? 0;
        $window = substr($skin, $lineOffset, max(0, ($lineOffsets[$d['line'] + 1] ?? strlen($skin)) - $lineOffset));
        $p = strpos($window, $d['needle']);
        if ($p === false) continue; // traceability failed -> skip
        $p += $lineOffset;
        $start = strrpos(substr($skin, 0, $p), 'player._("');
        if ($start === false) continue;
        $start += strlen('player._("');
        $end = $start;
        $len = strlen($skin);
        while ($end < $len) {
            $c = $skin[$end];
            if ($c === '\\') { $end += 2; continue; }
            if ($c === '"') break;
            $end++;
        }
        $raw = substr($skin, $start, $end - $start);
        $text = json_decode('"' . $raw . '"'); // JS string literal (handles \" and \uXXXX)
        if (!is_string($text) || trim($text) === '') continue;
        $out[] = [
            'id'                => 'skin:' . $d['id'],
            'entityId'          => 'skin:' . $d['id'],
            'title'             => $d['title'],
            'aliases'           => [],
            'type'              => $d['type'],
            'categories'        => ['informasi'],
            'tags'              => ['skin-inline', 'skin.js'],
            'recommendedFor'    => [],
            'capabilities'      => [],
            'facts'             => [],
            'sceneIds'          => [],
            'routeIds'          => [],
            'content'           => trim($text),
            'source'            => LS_SKIN_FILE . '#L' . $d['line'],
            'sourceId'          => $d['id'],
            'sourceType'        => 'skin-inline',
            'sourceFile'        => 'skin.js',
            'sourceLine'        => $d['line'],
            'provenance'        => 'skin-inline',
        ];
    }
    return $out;
}

/* ---------------- 5. entry assembly ---------------- */

function ls_build_entities(array $blocks, array $scenes, array $fetched): array
{
    $entries = [];
    foreach ($blocks as $b) {
        $id  = $b['id'];
        $s   = $b['state'];
        $langs = [];
        foreach (LS_LANGS as $lang) {
            $f = $fetched[$id][$lang] ?? null;
            if ($f && $f['ok']) $langs[$lang] = ls_extract_html($f['body']);
        }
        $primary = $langs['id'] ?? $langs['en'] ?? $langs['sa'] ?? $langs['zh'] ?? null;
        $title = trim((string) ($primary['title'] ?? ''));
        if ($title === '') {
            $title = ucwords(str_replace('-', ' ', $s));
        }
        $content = trim(implode("\n\n", $primary['paragraphs'] ?? []));

        $map = ls_map_scene($s, $b['openNext'], $scenes);

        $aliases = [str_replace('-', ' ', $s)];
        if ($b['audio']) $aliases[] = $b['audio'];
        foreach ($langs as $l) {
            if (!empty($l['title']) && ls_norm($l['title']) !== ls_norm($title)) $aliases[] = $l['title'];
        }
        $aliases = array_values(array_unique(array_filter($aliases)));

        $cats = match (true) {
            str_contains($s, 'gedung-') => ['bangunan', 'gedung'],
            str_contains($s, 'kereta')  => ['koleksi', 'kereta api'],
            str_contains($s, 'tugu')    => ['monumen'],
            $s === 'blueprint'          => ['sejarah', 'arsitektur'],
            default                     => ['informasi', 'pameran'],
        };

        $translations = [];
        foreach ($langs as $lang => $l) {
            $translations[$lang] = ['title' => $l['title'], 'content' => implode("\n\n", $l['paragraphs'])];
        }

        $entries[] = [
            'id'                => "webview:objinfo2:{$id}",
            'entityId'          => "objinfo2:{$id}",
            'title'             => $title,
            'aliases'           => $aliases,
            'type'              => 'information',
            'categories'        => $cats,
            'tags'              => ['webview', 'objinfo2', $s],
            'recommendedFor'    => [],
            'capabilities'      => [],
            'facts'             => [],
            'sceneIds'          => $map['sceneIds'],
            'routeIds'          => [],
            'content'           => $content,
            'source'            => LS_OBJINFO_BASE . "?info={$id}&lang=id&vd=1&tp=m",
            'sourceId'          => (string) $id,
            'sourceType'        => 'objinfo2',
            'sourceFile'        => 'skin.js',
            'sourceLine'        => $b['infoLine'],
            'vbState'           => $s,
            'mappingConfidence' => $map['confidence'],
            'mappingEvidence'   => $map['evidence'],
            'provenance'        => 'webview-remote',
            'translations'      => $translations,
        ];
    }
    return $entries;
}

/* ---------------- 6. contamination + merge + write ---------------- */

function ls_contamination_check(array $entries, array $inline): array
{
    $violations = [];
    foreach (array_merge($entries, $inline) as $e) {
        $hay = ls_norm($e['title'] . ' ' . implode(' ', $e['aliases']) . ' ' . $e['content'] . ' ' . $e['source']);
        foreach (LS_CONTAMINATION_TERMS as $term) {
            if ($hay !== '' && str_contains($hay, ls_norm($term))) {
                $violations[] = ['id' => $e['id'], 'term' => $term];
            }
        }
    }
    return $violations;
}

function ls_load_existing_manual(string $file): array
{
    if (!is_file($file)) return [];
    $raw = json_decode((string) file_get_contents($file), true);
    $keep = [];
    foreach ((array) ($raw['entries'] ?? []) as $e) {
        $id = (string) ($e['id'] ?? '');
        $srcType = (string) ($e['sourceType'] ?? '');
        if (str_starts_with($id, 'webview:') || str_starts_with($id, 'skin:')) continue;
        if (in_array($srcType, ['objinfo2', 'skin-inline'], true)) continue;
        $e['provenance'] = $e['provenance'] ?? 'manual-preserved';
        $keep[] = $e;
    }
    return $keep;
}

/* ---------------- 7. main ---------------- */

function ls_main(): void
{
    global $argv;
    $args = $argv ?? [];
    $dryRun         = in_array('--dry-run', $args, true);
    $noFetch        = in_array('--no-fetch', $args, true);
    $reportOnly     = in_array('--report-only', $args, true);
    $rebuildEmb     = in_array('--rebuild-embeddings', $args, true);

if (in_array('--help', $args, true) || in_array('-h', $args, true)) {
    echo "Usage: php tools/harvest-webview-knowledge.php [--dry-run] [--no-fetch] [--report-only] [--rebuild-embeddings]\n";
    exit(0);
}

$t0 = microtime(true);
$skin = ls_skin_core(ls_read(LS_SKIN_FILE));
$tourData = ls_read(LS_TOUR_DATA_FILE);

/* inventory */
$refs = ls_parse_iframes($skin);
$counts = [];
foreach ($refs as $r) {
    $cls = ls_classify($r);
    $counts[$cls] = ($counts[$cls] ?? 0) + 1;
    $counts['total'] = ($counts['total'] ?? 0) + 1;
}
$uniqueIds = [];
$langsSeen = [];
foreach ($refs as $r) {
    if ($r['kind'] === 'objinfo2') { $uniqueIds[$r['id']] = true; $langsSeen[$r['lang']] = true; }
}
$uniqueIds = array_map('intval', array_keys($uniqueIds));
sort($uniqueIds);

/* VB-state mapping */
$blocks = ls_parse_vb_blocks($skin);
$scenes = ls_parse_scenes($tourData);

/* fetch */
$fetched = [];
$failures = [];
foreach ($uniqueIds as $id) {
    $fetched[$id] = [];
    foreach (LS_LANGS as $lang) {
        $res = ls_fetch_objinfo($id, $lang, $noFetch);
        $fetched[$id][$lang] = $res;
        if (!$res['ok']) $failures[] = ['id' => $id, 'lang' => $lang, 'status' => $res['status'] ?? 0, 'error' => $res['error'] ?? ''];
    }
}
if ($failures && $noFetch) {
    fwrite(STDERR, "harvest-webview-knowledge: --no-fetch but missing raw cache for: "
        . implode(', ', array_map(static fn($f) => "{$f['id']}:{$f['lang']}", $failures)) . "\n");
}

$entries = ls_build_entities($blocks, $scenes, $fetched);
$inline = ls_inline_texts($skin);
$violations = ls_contamination_check($entries, $inline);

/* report */
$byConfidence = [];
foreach ($entries as $e) $byConfidence[$e['mappingConfidence']] = ($byConfidence[$e['mappingConfidence']] ?? 0) + 1;
$fetchOk = count($uniqueIds) * count(LS_LANGS) - count($failures);

$report = [
    'generatedAt'     => gmdate('c'),
    'dryRun'          => $dryRun,
    'noFetch'         => $noFetch,
    'source'          => ['skin' => LS_SKIN_FILE, 'tourData' => LS_TOUR_DATA_FILE],
    'inventory'       => [
        'totalIframes'      => $counts['total'] ?? 0,
        'byClass'           => array_diff_key($counts, ['total' => true]),
        'uniqueObjinfoIds'  => count($uniqueIds),
        'objinfoIds'        => $uniqueIds,
        'uniqueLanguages'   => array_values(array_keys($langsSeen)),
        'vbStatesMapped'    => count($blocks),
    ],
    'fetch'           => [
        'attempted' => count($uniqueIds) * count(LS_LANGS),
        'ok'        => $fetchOk,
        'failed'    => $failures,
    ],
    'entities'        => [
        'built'        => count($entries),
        'inlineSkin'   => count($inline),
        'sceneMapping' => $byConfidence,
    ],
    'contamination'   => ['checked' => true, 'terms' => LS_CONTAMINATION_TERMS, 'violations' => $violations],
    'elapsedSeconds'  => round(microtime(true) - $t0, 2),
];

if (!$reportOnly && !$dryRun) {
    if ($violations) {
        fwrite(STDERR, "harvest-webview-knowledge: CONTAMINATION DETECTED — publish blocked.\n");
        foreach ($violations as $v) fwrite(STDERR, "  {$v['id']}: term '{$v['term']}'\n");
        ls_write(LS_REPORT_OUT, ls_json($report));
        exit(2);
    }
    $manual = ls_load_existing_manual(LS_KNOWLEDGE_OUT);
    $all = array_merge($manual, $entries, $inline);
    $knowledge = [
        'version' => 2,
        'tourId'  => 'lawang-sewu',
        'updatedAt' => gmdate('c'),
        'generatedBy' => 'tools/harvest-webview-knowledge.php',
        'entries' => $all,
    ];
    $json = ls_json($knowledge);
    ls_write(LS_KNOWLEDGE_OUT, $json);
    ls_write(LS_KNOWLEDGE_SPEC, $json);

    /* webview-map.json */
    $entityMap = [];
    foreach ($blocks as $b) {
        $entityMap[(string) $b['id']] = [
            'state' => $b['state'],
            'openNext' => $b['openNext'],
            'audio' => $b['audio'],
            'infoLine' => $b['infoLine'],
            'sourceUrl' => LS_OBJINFO_BASE . "?info={$b['id']}&lang=id&vd=1&tp=m",
        ];
    }
    ls_write(LS_WEBVIEW_MAP_OUT, ls_json([
        'generatedAt' => gmdate('c'),
        'source' => ['skin' => LS_SKIN_FILE],
        'inventory' => [
            'totalIframes' => $counts['total'] ?? 0,
            'byClass' => array_diff_key($counts, ['total' => true]),
            'uniqueObjinfoIds' => count($uniqueIds),
            'ignored' => array_values(array_filter(array_map(static function ($r) {
                if ($r['kind'] === 'chatbase') return ['kind' => 'chatbase', 'url' => $r['url'], 'reason' => 'existing third-party chatbot, excluded'];
                if ($r['kind'] === 'webview-local') return ['kind' => 'webview-local', 'page' => $r['page'], 'reason' => 'Lampung tour webview, contamination risk, excluded'];
                return null;
            }, $refs))),
        ],
        'entities' => $entityMap,
        'inlineSkinTexts' => array_map(static fn($t) => ['id' => $t['id'], 'line' => $t['sourceLine'], 'source' => 'skin.js'], $inline),
    ]));

    if ($rebuildEmb) {
        require LS_ROOT . '/api/ai-core.php';
        ai_load_env();
        $config = ai_config();
        $vectors = ai_embedding_index(ai_load_knowledge(), $config);
        $report['embeddings'] = [
            'mode' => $vectors ? 'hybrid' : 'lexical-fallback',
            'vectors' => count($vectors),
            'cache' => LS_ROOT . '/api/cache/ai/knowledge-embeddings.json',
        ];
        fwrite(STDERR, "harvest-webview-knowledge: embeddings rebuilt (" . count($vectors) . " vectors, " . ($vectors ? 'hybrid' : 'lexical-fallback') . ").\n");
    }

    $report['written'] = [
        'knowledge' => LS_KNOWLEDGE_OUT,
        'knowledgeSpec' => LS_KNOWLEDGE_SPEC,
        'webviewMap' => LS_WEBVIEW_MAP_OUT,
        'report' => LS_REPORT_OUT,
        'entriesTotal' => count($all),
        'manualPreserved' => count($manual),
    ];
}

ls_write(LS_REPORT_OUT, ls_json($report));

echo "Lawang Sewu webview harvest\n";
echo "  iframes:   total {$counts['total']}, objinfo2 IDs " . count($uniqueIds) . " (" . implode(',', $uniqueIds) . ")\n";
echo "  languages: " . implode(',', array_keys($langsSeen)) . "\n";
echo "  vb states: " . count($blocks) . " mapped, scenes " . count($scenes) . "\n";
echo "  fetch:     {$fetchOk}/" . (count($uniqueIds) * count(LS_LANGS)) . " ok" . ($failures ? ' (' . count($failures) . ' failed)' : '') . "\n";
echo "  entities:  " . count($entries) . " webview + " . count($inline) . " inline skin\n";
echo "  scene:     " . implode(', ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($byConfidence), $byConfidence)) . "\n";
if ($violations) {
    echo "  contamination: " . count($violations) . " VIOLATIONS — publish blocked (exit 2)\n";
    exit(2);
}
echo "  contamination: none\n";
echo "  mode:      " . ($dryRun ? 'dry-run (no writes)' : ($reportOnly ? 'report-only' : 'publish')) . "\n";
if ($dryRun) echo "  NOTE: dry-run — knowledge.json NOT written. Run without --dry-run to publish.\n";
if ($failures) {
    fwrite(STDERR, "harvest-webview-knowledge: " . count($failures) . " fetch failure(s) recorded — inspect report.\n");
    if (!$noFetch) exit(3);
}
    exit(0);
}

if (!defined('LS_TEST_MODE')) {
    ls_main();
}
